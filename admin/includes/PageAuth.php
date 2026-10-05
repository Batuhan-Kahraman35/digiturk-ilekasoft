<?php
/**
 * Sayfa Yetki Kontrolü
 * 
 * Kullanıcının mevcut sayfaya erişim yetkisini kontrol eder.
 * departman_id=1 (Administrator) için tüm yetkilere izin verir.
 * Diğer departmanlar için menu_sayfa_yetkiler tablosunu kontrol eder.
 */

class PageAuth {
    
    /**
     * Sayfa yetkilerini kontrol et
     * 
     * @param int $userId Kullanıcı ID
     * @param int $departmanId Departman ID
     * @param string $pageFile Sayfa dosya adı (örn: stok-yonetimi.php)
     * @return array Yetki bilgileri
     */
    public static function checkPagePermissions($userId, $departmanId, $pageFile) {
        // Administrator departmanı için tüm yetkiler açık
        if ($departmanId == 1) {
            return [
                'has_access' => true,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_birim' => true,
                'can_view_own_records' => false, // Admin tüm kayıtları görür
                'is_admin' => true
            ];
        }
        
        $db = Database::getInstance();
        
        // Sayfa ID'sini bul (RTRIM ile trailing space kontrolü)
        $page = $db->fetchOne("
            SELECT sayfalar_id 
            FROM Menu_Sayfalar 
            WHERE RTRIM(LTRIM(sayfalar_sayfa_url)) = RTRIM(LTRIM(?)) AND sayfalar_durum = 1
        ", [trim($pageFile)]);
        
        if (!$page) {
            // Sayfa tanımı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_birim' => false,
                'is_admin' => false,
                'error' => 'Sayfa tanımı bulunamadı'
            ];
        }
        
        // Departman yetkilerini kontrol et
        $permissions = $db->fetchOne("
            SELECT 
                gor,
                ekle,
                duzenle,
                sil,
                birim_gor,
                kendi_kullanicini_gor
            FROM Menu_SayfaYetkileri
            WHERE departman_id = ? 
              AND sayfa_id = ? 
              AND durum = 1
        ", [$departmanId, $page['sayfalar_id']]);
        
        if (!$permissions) {
            // Yetki kaydı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_birim' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Bu sayfaya erişim yetkiniz yok'
            ];
        }
        
        return [
            'has_access' => (bool)$permissions['gor'],
            'can_view' => (bool)$permissions['gor'],
            'can_add' => (bool)$permissions['ekle'],
            'can_edit' => (bool)$permissions['duzenle'],
            'can_delete' => (bool)$permissions['sil'],
            'can_view_birim' => (bool)$permissions['birim_gor'],
            'can_view_own_records' => (bool)$permissions['kendi_kullanicini_gor'],
            'is_admin' => false
        ];
    }
    
    /**
     * Yetkisiz erişim durumunda hata sayfası göster
     */
    public static function accessDenied($message = 'Bu sayfaya erişim yetkiniz yok!') {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erişim Engellendi</title>
            <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        </head>
        <body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="text-center text-white">
                <i class="bi bi-shield-x" style="font-size: 120px; opacity: 0.8;"></i>
                <h1 class="display-4 mt-4">403 - Erişim Engellendi</h1>
                <p class="lead mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="/admin/anasayfa" class="btn btn-light btn-lg">
                    <i class="bi bi-house-door"></i> Ana Sayfaya Dön
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
