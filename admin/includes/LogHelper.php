<?php
/**
 * Değişiklik Log Helper
 * Tüm sayfalardaki CRUD işlemlerini otomatik loglar
 * 
 * Kullanım:
 * 1. UPDATE öncesi: $eskiKayit = $db->fetchOne("SELECT * FROM Tablo WHERE id = ?", [$id]);
 * 2. UPDATE yap
 * 3. UPDATE sonrası: $yeniKayit = $db->fetchOne("SELECT * FROM Tablo WHERE id = ?", [$id]);
 * 4. Log: logKayitDegisiklikleri($db, 'sayfa-adi', 'Tablo', $id, $eskiKayit, $yeniKayit, $userId);
 */

/**
 * Kayıt değişikliklerini otomatik karşılaştırıp loglar
 * 
 * @param object $db Database bağlantısı
 * @param string $sayfa Sayfa adı (örn: 'sozlesme-form', 'cari-yonetimi')
 * @param string $tablo Tablo adı (örn: 'Sozlesmeler', 'Cariler')
 * @param int $kayitId Kaydın ID'si
 * @param array|null $eskiKayit UPDATE öncesi kayıt (INSERT için null)
 * @param array|null $yeniKayit UPDATE sonrası kayıt (DELETE için null)
 * @param int $kullaniciId İşlemi yapan kullanıcı ID
 * @param string|null $aciklama Opsiyonel açıklama
 * @return bool Başarılı mı
 */
function logKayitDegisiklikleri($db, $sayfa, $tablo, $kayitId, $eskiKayit, $yeniKayit, $kullaniciId, $aciklama = null) {
    try {
        // İşlem tipini belirle
        if ($eskiKayit === null && $yeniKayit !== null) {
            $islemTipi = 'INSERT';
        } elseif ($eskiKayit !== null && $yeniKayit === null) {
            $islemTipi = 'DELETE';
        } elseif ($eskiKayit !== null && $yeniKayit !== null) {
            $islemTipi = 'UPDATE';
        } else {
            return false; // Her ikisi de null ise log atma
        }
        
        // Değişiklikleri hesapla
        $degisiklikler = [];
        
        if ($islemTipi === 'INSERT') {
            // Yeni kayıt - tüm alanları logla
            foreach ($yeniKayit as $alan => $deger) {
                // Datetime nesnelerini string'e çevir
                if ($deger instanceof DateTime) {
                    $deger = $deger->format('Y-m-d H:i:s');
                }
                $degisiklikler[$alan] = [
                    'eski' => null,
                    'yeni' => $deger
                ];
            }
        } elseif ($islemTipi === 'DELETE') {
            // Silinen kayıt - tüm alanları logla
            foreach ($eskiKayit as $alan => $deger) {
                if ($deger instanceof DateTime) {
                    $deger = $deger->format('Y-m-d H:i:s');
                }
                $degisiklikler[$alan] = [
                    'eski' => $deger,
                    'yeni' => null
                ];
            }
        } else {
            // UPDATE - sadece değişen alanları logla
            foreach ($yeniKayit as $alan => $yeniDeger) {
                $eskiDeger = $eskiKayit[$alan] ?? null;
                
                // Datetime nesnelerini string'e çevir
                if ($eskiDeger instanceof DateTime) {
                    $eskiDeger = $eskiDeger->format('Y-m-d H:i:s');
                }
                if ($yeniDeger instanceof DateTime) {
                    $yeniDeger = $yeniDeger->format('Y-m-d H:i:s');
                }
                
                // Değer karşılaştırması (tip dönüşümü ile)
                if (normalizeValue($eskiDeger) != normalizeValue($yeniDeger)) {
                    $degisiklikler[$alan] = [
                        'eski' => $eskiDeger,
                        'yeni' => $yeniDeger
                    ];
                }
            }
            
            // Değişiklik yoksa log atma
            if (empty($degisiklikler)) {
                return true;
            }
        }
        
        // JSON'a çevir
        $degisikliklerJson = json_encode($degisiklikler, JSON_UNESCAPED_UNICODE);
        
        // Log kaydı oluştur
        $db->execute("
            INSERT INTO Sistem_DegisiklikLog (
                log_sayfa, log_tablo, log_kayit_id, log_islem_tipi,
                log_degisiklikler, log_aciklama, log_kullanici_id, log_tarih
            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())
        ", [
            $sayfa, $tablo, $kayitId, $islemTipi,
            $degisikliklerJson, $aciklama, $kullaniciId
        ]);
        
        return true;
    } catch (Exception $e) {
        // Log hatası ana işlemi engellemez, sadece error log'a yaz
        error_log("LogHelper Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Değer normalizasyonu (karşılaştırma için)
 */
function normalizeValue($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (string)$value;
    }
    return $value;
}

/**
 * Belirli bir kaydın log geçmişini getir
 * 
 * @param object $db Database bağlantısı
 * @param string $tablo Tablo adı
 * @param int $kayitId Kayıt ID
 * @param int $limit Maksimum kayıt sayısı
 * @return array Log kayıtları
 */
function getKayitLogGecmisi($db, $tablo, $kayitId, $limit = 50) {
    return $db->fetchAll("
        SELECT 
            l.log_id,
            l.log_sayfa,
            l.log_tablo,
            l.log_kayit_id,
            l.log_islem_tipi,
            l.log_degisiklikler,
            l.log_aciklama,
            CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
        FROM Sistem_DegisiklikLog l
        LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
        WHERE l.log_tablo = ? AND l.log_kayit_id = ?
        ORDER BY l.log_tarih DESC
        OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
    ", [$tablo, $kayitId, $limit]);
}

/**
 * Belirli bir sayfanın tüm log geçmişini getir
 * 
 * @param object $db Database bağlantısı
 * @param string $sayfa Sayfa adı
 * @param int $limit Maksimum kayıt sayısı
 * @return array Log kayıtları
 */
function getSayfaLogGecmisi($db, $sayfa, $limit = 100) {
    return $db->fetchAll("
        SELECT 
            l.log_id,
            l.log_sayfa,
            l.log_tablo,
            l.log_kayit_id,
            l.log_islem_tipi,
            l.log_degisiklikler,
            l.log_aciklama,
            CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
        FROM Sistem_DegisiklikLog l
        LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
        WHERE l.log_sayfa = ?
        ORDER BY l.log_tarih DESC
        OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
    ", [$sayfa, $limit]);
}

/**
 * İşlem tipi için Türkçe etiket
 */
function getIslemTipiLabel($islemTipi) {
    $labels = [
        'INSERT' => ['text' => 'Eklendi', 'badge' => 'success', 'icon' => 'bi-plus-circle'],
        'UPDATE' => ['text' => 'Güncellendi', 'badge' => 'warning', 'icon' => 'bi-pencil'],
        'DELETE' => ['text' => 'Silindi', 'badge' => 'danger', 'icon' => 'bi-trash']
    ];
    return $labels[$islemTipi] ?? ['text' => $islemTipi, 'badge' => 'secondary', 'icon' => 'bi-question'];
}

/**
 * Alan adını Türkçeye çevir (opsiyonel mapping)
 */
function getAlanAdiLabel($tablo, $alanAdi) {
    // Genel alan adı çevirileri
    $genelCeviriler = [
        'olusturma_tarihi' => 'Oluşturma Tarihi',
        'guncelleme_tarihi' => 'Güncelleme Tarihi',
        'olusturan_id' => 'Oluşturan',
        'guncelleyen_id' => 'Güncelleyen',
        'durum' => 'Durum',
        'aciklama' => 'Açıklama',
        'tarih' => 'Tarih'
    ];
    
    // Tablo bazlı özel çeviriler
    $tabloCevirileri = [
        'Cariler' => [
            'cari_id' => 'Cari ID',
            'cari_adi' => 'Cari Adı',
            'cari_unvan' => 'Ünvan',
            'cari_tip' => 'Cari Tipi',
            'cari_telefon' => 'Telefon',
            'cari_email' => 'E-posta',
            'cari_vergi_no' => 'Vergi No',
            'cari_vergi_dairesi' => 'Vergi Dairesi',
            'cari_adres' => 'Adres',
            'cari_durum' => 'Durum'
        ]
    ];
    
    // Önce tablo bazlı, sonra genel çevirilere bak
    if (isset($tabloCevirileri[$tablo][$alanAdi])) {
        return $tabloCevirileri[$tablo][$alanAdi];
    }
    if (isset($genelCeviriler[$alanAdi])) {
        return $genelCeviriler[$alanAdi];
    }
    
    // Bulunamazsa alan adını formatla (snake_case -> Title Case)
    return ucwords(str_replace('_', ' ', $alanAdi));
}
