<?php
/**
 * Admin Panel - Çıkış İşlemi
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';

// Çıkış işlemi
Auth::logout();

// Giriş sayfasına yönlendir
redirect('/admin/login.php');
