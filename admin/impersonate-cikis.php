<?php
require_once __DIR__ . '/auth.php';

requireAuth();

if (!Auth::isImpersonating()) {
    header('Location: /admin/anasayfa');
    exit;
}

Auth::stopImpersonate();

header('Location: /admin/personel-yonetimi');
exit;
