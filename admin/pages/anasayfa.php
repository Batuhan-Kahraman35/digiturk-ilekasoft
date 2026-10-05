<?php
/**
 * Admin Panel - Ana Sayfa
 * Kullanıcının departmanına göre uygun dashboard içeriğini gösterir
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Kullanıcının departman ID'si
$departmanId = $user['departman_id'];

// Departman bilgilerini çek (dashboard dosyası var mı?)
$departman = $db->fetchOne("
    SELECT departman_dashboard_dosya 
    FROM departmanlar 
    WHERE departman_id = ? AND departman_durum = 1
", [$departmanId]);

// Dashboard dosyası belirle
$dashboardFile = 'dashboard'; // Default

if ($departman && !empty($departman['departman_dashboard_dosya'])) {
    $dashboardFile = $departman['departman_dashboard_dosya'];
}

// Güvenlik kontrolü: Dosya gerçekten var mı?
$fullPath = __DIR__ . '/' . $dashboardFile . '.php';
if (!file_exists($fullPath)) {
    // Dosya yoksa default'a düş
    $dashboardFile = 'dashboard';
    $fullPath = __DIR__ . '/dashboard.php';
}

// Dashboard içeriğini yükle (redirect değil, include!)
// Bu sayede URL /admin/anasayfa olarak kalır
include $fullPath;
exit;
