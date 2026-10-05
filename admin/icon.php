<?php
/**
 * PWA İkon Üretici
 * Logoyu (site_ayarlari_logo_url) kare tuval üzerine ortalayıp istenen boyutta PNG döner.
 * Kullanım: /admin/icon.php?size=192  |  /admin/icon.php?size=512&maskable=1
 *
 * Üretilen PNG diskte cache'lenir: admin/assets/images/pwa-cache
 * Logo değişirse (filemtime) cache anahtarı değişir, yeni ikon üretilir.
 */

require_once __DIR__ . '/db.php';

$size = (int)($_GET['size'] ?? 192);
if ($size < 48)   $size = 48;
if ($size > 1024) $size = 1024;
$maskable = !empty($_GET['maskable']);

// ─── Logo yolunu çöz ───
$db = Database::getInstance();
$ayar = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_logo_url, site_ayarlari_favicon_url, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");
$siteTitle = $ayar['site_ayarlari_site_title'] ?? 'D';

// Kaynak görsel: önce logo, yoksa favicon. İkisi de boşsa baş harf fallback'i çizilir.
$logoUrl = '';
foreach (['site_ayarlari_logo_url', 'site_ayarlari_favicon_url'] as $_kolon) {
    if (!empty($ayar[$_kolon])) { $logoUrl = trim($ayar[$_kolon]); break; }
}

// URL'yi filesystem yoluna çevir (sidebar.php ile aynı mantık)
$logoPath   = null;
$logoRemote = null;
if ($logoUrl) {
    if (strpos($logoUrl, 'http') === 0) {
        $logoRemote = $logoUrl;
    } else {
        if (strpos($logoUrl, 'assets/') === 0) {
            $rel = '/admin/' . $logoUrl;
        } elseif (strpos($logoUrl, '/') !== 0) {
            $rel = '/admin/assets/' . $logoUrl;
        } else {
            $rel = $logoUrl;
        }
        $logoPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $rel;
    }
}

// ─── Cache (aynı logo + boyut için diskte tut) ───
$cacheDir = __DIR__ . '/assets/images/pwa-cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$srcStamp  = $logoPath && is_file($logoPath) ? filemtime($logoPath) : md5((string)$logoUrl);
$cacheFile = $cacheDir . '/icon_' . $size . ($maskable ? '_m' : '') . '_' . substr(md5($logoUrl . $srcStamp), 0, 8) . '.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

if (is_file($cacheFile)) {
    readfile($cacheFile);
    exit;
}

// ─── Kaynak logoyu yükle ───
$src     = null;
$imgData = null;
if ($logoRemote) {
    $ch = curl_init($logoRemote);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    $imgData = curl_exec($ch);
    curl_close($ch);
} elseif ($logoPath && is_file($logoPath)) {
    $imgData = file_get_contents($logoPath);
}
if ($imgData) {
    $src = @imagecreatefromstring($imgData);
}

// ─── Kare tuval oluştur ───
$canvas = imagecreatetruecolor($size, $size);
imagesavealpha($canvas, true);

if ($maskable) {
    // Maskable ikon platform tarafından daire/kare/damla şeklinde KIRPILIR.
    // Şeffaf zemin bırakılırsa kırpılan kenarlarda boşluk görünür; bu yüzden
    // manifest'teki background_color ile aynı dolu zemin kullanılır.
    $zemin = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $zemin);
} else {
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefill($canvas, 0, 0, $transparent);
}

// Maskable ikon için güvenli alan (kenarlardan %10 boşluk)
$pad  = $maskable ? (int)round($size * 0.10) : 0;
$area = $size - ($pad * 2);

if ($src) {
    $sw = imagesx($src);
    $sh = imagesy($src);
    // Oranı koru, alana sığdır
    $ratio = min($area / $sw, $area / $sh);
    $dw = (int)round($sw * $ratio);
    $dh = (int)round($sh * $ratio);
    $dx = (int)round(($size - $dw) / 2);
    $dy = (int)round(($size - $dh) / 2);
    // maskable: harmanla ki logonun şeffaf pikselleri altındaki dolu zemini göstersin.
    // normal: harmanlama kapalı -> kaynağın şeffaflığı olduğu gibi korunur.
    imagealphablending($canvas, $maskable);
    imagesavealpha($canvas, true);
    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);
} else {
    // Logo yoksa: renkli kare + baş harf (fallback)
    $bg = imagecolorallocate($canvas, 13, 110, 253); // #0d6efd
    imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
    $harf     = mb_strtoupper(mb_substr($siteTitle, 0, 1));
    $white    = imagecolorallocate($canvas, 255, 255, 255);
    $fontSize = $size * 0.45;

    // Mevcut olan ilk TTF'yi kullan; hiçbiri yoksa imagestring() fallback'i devreye girer
    // (imagestring sabit ~15px bitmap font çizer, büyük tuvalde okunmaz -> TTF tercih edilir).
    $font = null;
    foreach (['NotoSans-Regular.ttf', 'arial.ttf'] as $_f) {
        $_yol = __DIR__ . '/assets/fonts/' . $_f;
        if (is_file($_yol)) { $font = $_yol; break; }
    }

    if ($font) {
        $box = imagettfbbox($fontSize, 0, $font, $harf);
        $tx  = ($size - ($box[2] - $box[0])) / 2 - $box[0];
        $ty  = ($size - ($box[7] - $box[1])) / 2 - $box[1];
        imagettftext($canvas, $fontSize, 0, (int)$tx, (int)$ty, $white, $font, $harf);
    } else {
        imagestring($canvas, 5, (int)($size / 2 - 5), (int)($size / 2 - 8), $harf, $white);
    }
}

// ─── Çıktı + cache ───
imagepng($canvas, $cacheFile);
imagepng($canvas);
imagedestroy($canvas);
