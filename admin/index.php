<?php
/**
 * Admin Panel - Giriş Kontrol ve Yönlendirme
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';

// Oturum kontrolü
if (Auth::check()) {
    // Kullanıcı giriş yapmışsa ana sayfaya yönlendir
    header('Location: pages/anasayfa.php');
    exit;
} else {
    // Giriş yapmamışsa login sayfasına yönlendir
    header('Location: login.php');
    exit;
}
