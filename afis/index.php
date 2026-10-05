<?php
/**
 * Afiş Oluşturucu - Şifresiz genel erişim sayfası
 * Şablon görseller: afis/gorseller/ klasörü (jpg, jpeg, png, webp)
 * Görsel üretimi tarayıcıda Canvas ile yapılır, sunucuda dosya oluşmaz.
 */

require_once __DIR__ . '/../admin/db.php';

/* --- Afişe basılacak maçlar: Süper Lig, bugünden itibaren --- */
$maclar = [];
try {
    $db = Database::getInstance();

    // Lig kodu sabit yazılmaz, fikstür tablosundan çözülür.
    $lig = $db->fetchOne("
        SELECT TOP 1 SporFikstur_LigKodu AS kod
        FROM SporFikstur
        WHERE Durum = 1 AND SporFikstur_LigAdi LIKE N'%Süper Lig%'
        ORDER BY SporFikstur_LigKodu
    ");

    if (!empty($lig['kod'])) {
        $maclar = $db->fetchAll("
            SELECT
                f.SporFikstur_Id AS id,
                CONVERT(VARCHAR(16), f.SporFikstur_MacTarihi, 120) AS tarih,
                ISNULL(ev.SporTakimlar_Ad,  f.SporFikstur_EvSahibi)  AS ev,
                ISNULL(dep.SporTakimlar_Ad, f.SporFikstur_Deplasman) AS deplasman,
                ev.SporTakimlar_LogoYolu  AS ev_logo,
                dep.SporTakimlar_LogoYolu AS dep_logo
            FROM SporFikstur f
            LEFT JOIN SporTakimlar ev  ON ev.SporTakimlar_Id  = f.SporFikstur_EvTakimId
            LEFT JOIN SporTakimlar dep ON dep.SporTakimlar_Id = f.SporFikstur_DeplasmanTakimId
            WHERE f.Durum = 1
              AND f.SporFikstur_LigKodu = ?
              AND f.SporFikstur_MacTarihi >= ?
            ORDER BY f.SporFikstur_MacTarihi ASC, f.SporFikstur_Id ASC
        ", [$lig['kod'], date('Y-m-d') . ' 00:00:00']);
    }
} catch (Throwable $e) {
    $maclar = []; // Fikstür okunamazsa afiş maç satırı olmadan çalışmaya devam eder
}

/* Maç etiketi: "Beşiktaş - Çorum FK · 31 Ağustos Pazar 21:30" */
$gunler  = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
$aylar   = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
foreach ($maclar as $i => $m) {
    $ts = strtotime($m['tarih']);
    $maclar[$i]['takimlar'] = $m['ev'] . ' - ' . $m['deplasman'];
    $maclar[$i]['zaman']    = $ts
        ? (int)date('j', $ts) . ' ' . $aylar[(int)date('n', $ts)] . ' ' . $gunler[(int)date('w', $ts)] . ' ' . date('H:i', $ts)
        : '';
}

$gorselDizin  = __DIR__ . '/gorseller';
$izinliUzanti = ['jpg', 'jpeg', 'png', 'webp'];
$sablonlar    = [];

if (is_dir($gorselDizin)) {
    $dosyalar = scandir($gorselDizin);
    natcasesort($dosyalar);
    foreach ($dosyalar as $dosya) {
        if ($dosya === '.' || $dosya === '..') {
            continue;
        }
        $uzanti = strtolower(pathinfo($dosya, PATHINFO_EXTENSION));
        if (!in_array($uzanti, $izinliUzanti, true)) {
            continue;
        }
        $ad = pathinfo($dosya, PATHINFO_FILENAME);
        $ad = trim(preg_replace('/\s+/u', ' ', str_replace(['-', '_'], ' ', $ad)));
        $sablonlar[] = [
            'url' => '/afis/gorseller/' . rawurlencode($dosya),
            'ad'  => mb_convert_case($ad, MB_CASE_TITLE, 'UTF-8'),
        ];
    }
    $sablonlar = array_values($sablonlar);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Afiş Oluştur</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.ico" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root{
    --kirmizi:#E30613;
    --koyu:#111418;
    --panel:#1B1F26;
    --cizgi:#2C323C;
    --metin:#F2F4F7;
    --soluk:#9AA3B2;
}
body{
    font-family:'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background:var(--koyu); color:var(--metin);
    min-height:100vh; padding:20px 16px 48px;
    -webkit-font-smoothing:antialiased;
}
.sarmal{ max-width:760px; margin:0 auto; }
header{ text-align:center; margin-bottom:26px; }
header h1{ font-size:22px; font-weight:800; letter-spacing:-.4px; }
header h1 span{ color:var(--kirmizi); }
header p{ font-size:13px; color:var(--soluk); margin-top:6px; }
.kart{
    background:var(--panel); border:1px solid var(--cizgi);
    border-radius:16px; padding:18px; margin-bottom:16px;
}
.kart h2{
    font-size:13px; font-weight:700; text-transform:uppercase;
    letter-spacing:.6px; color:var(--soluk); margin-bottom:14px;
    display:flex; align-items:center; gap:8px;
}
.kart h2 i{
    width:22px; height:22px; border-radius:6px; background:var(--kirmizi);
    color:#fff; font-style:normal; font-size:12px; font-weight:700;
    display:flex; align-items:center; justify-content:center; flex:0 0 auto;
}
.sablonlar{
    display:grid; grid-template-columns:repeat(auto-fill, minmax(120px,1fr)); gap:12px;
}
.sablon{
    border:2px solid var(--cizgi); border-radius:12px; overflow:hidden;
    cursor:pointer; background:#0C0F13; transition:border-color .15s;
    position:relative; padding:0; display:block; width:100%; text-align:left;
}
.sablon img{ width:100%; aspect-ratio:3/4; object-fit:cover; display:block; }
.sablon span{
    display:block; padding:7px 8px; font-size:11px; color:var(--soluk);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.sablon.secili{ border-color:var(--kirmizi); }
.sablon.secili span{ color:var(--metin); }
.sablon.secili::after{
    content:'\2713'; position:absolute; top:8px; right:8px;
    width:24px; height:24px; border-radius:50%; background:var(--kirmizi);
    color:#fff; font-size:14px; font-weight:700;
    display:flex; align-items:center; justify-content:center;
}
.alan{ margin-bottom:14px; }
.alan:last-child{ margin-bottom:0; }
.alan label{ display:block; font-size:12px; color:var(--soluk); margin-bottom:6px; }
.alan input{
    width:100%; padding:13px 14px; border-radius:10px;
    border:1px solid var(--cizgi); background:#0C0F13; color:var(--metin);
    font-family:inherit; font-size:15px; outline:none; transition:border-color .15s;
}
.alan input:focus{ border-color:var(--kirmizi); }
.alan select{
    width:100%; padding:13px 14px; border-radius:10px;
    border:1px solid var(--cizgi); background:#0C0F13; color:var(--metin);
    font-family:inherit; font-size:15px; outline:none; transition:border-color .15s;
    appearance:none; -webkit-appearance:none;
    background-image:url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%239AA3B2'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 10px center; background-size:20px;
    padding-right:38px;
}
.alan select:focus{ border-color:var(--kirmizi); }
.alan .not{ font-size:12px; color:var(--soluk); margin-top:6px; }
.btn{
    width:100%; padding:15px; border:none; border-radius:12px;
    font-family:inherit; font-size:15px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:opacity .15s;
}
.btn:active{ opacity:.75; }
.btn-ana{ background:var(--kirmizi); color:#fff; }
.btn-ikincil{ background:#232830; color:var(--metin); border:1px solid var(--cizgi); }
.btn:disabled{ opacity:.45; cursor:not-allowed; }
.onizleme{ display:none; }
.onizleme.acik{ display:block; }
#tuval{
    width:100%; height:auto; border-radius:12px; display:block;
    background:#0C0F13; margin-bottom:14px;
}
.butonlar{ display:grid; gap:10px; grid-template-columns:1fr; }
@media(min-width:520px){ .butonlar{ grid-template-columns:1fr 1fr; } }
.uyari{
    background:#2A1B1D; border:1px solid #58282D; color:#FFB4B8;
    border-radius:12px; padding:14px; font-size:13px; line-height:1.6;
}
.bilgi{ font-size:12px; color:var(--soluk); text-align:center; margin-top:10px; line-height:1.6; }
.toast{
    position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px);
    background:#232830; border:1px solid var(--cizgi); color:var(--metin);
    padding:12px 20px; border-radius:10px; font-size:13px;
    opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:99;
}
.toast.acik{ opacity:1; transform:translateX(-50%) translateY(0); }
</style>
</head>
<body>
<div class="sarmal">

    <header>
        <h1>Afiş <span>Oluştur</span></h1>
        <p>Şablonu seç, bilgileri yaz, afişini indir veya paylaş.</p>
    </header>

<?php if (empty($sablonlar)): ?>
    <div class="uyari">
        Henüz şablon görsel yüklenmemiş.<br>
        <strong>afis/gorseller/</strong> klasörüne .jpg, .png veya .webp uzantılı görseller ekleyin;
        sayfa bunları otomatik listeler.
    </div>
<?php else: ?>

    <div class="kart">
        <h2><i>1</i> Şablon Seç</h2>
        <div class="sablonlar" id="sablonListe">
        <?php foreach ($sablonlar as $i => $s): ?>
            <button type="button" class="sablon<?= $i === 0 ? ' secili' : '' ?>" data-url="<?= htmlspecialchars($s['url']) ?>">
                <img src="<?= htmlspecialchars($s['url']) ?>" alt="<?= htmlspecialchars($s['ad']) ?>" loading="lazy">
                <span><?= htmlspecialchars($s['ad']) ?></span>
            </button>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="kart">
        <h2><i>2</i> Bilgileri Gir</h2>
        <div class="alan">
            <label for="mac">Maç (Süper Lig)</label>
            <select id="mac">
                <option value="">Maç bilgisi ekleme</option>
<?php foreach ($maclar as $m): ?>
                <option value="<?= (int)$m['id'] ?>"
                        data-takimlar="<?= htmlspecialchars($m['takimlar']) ?>"
                        data-ev="<?= htmlspecialchars($m['ev']) ?>"
                        data-dep="<?= htmlspecialchars($m['deplasman']) ?>"
                        data-zaman="<?= htmlspecialchars($m['zaman']) ?>"
                        data-ev-logo="<?= htmlspecialchars($m['ev_logo'] ?? '') ?>"
                        data-dep-logo="<?= htmlspecialchars($m['dep_logo'] ?? '') ?>"><?= htmlspecialchars($m['takimlar'] . ' · ' . $m['zaman']) ?></option>
<?php endforeach; ?>
            </select>
<?php if (empty($maclar)): ?>
            <p class="not">Şu an listelenecek maç yok.</p>
<?php endif; ?>
        </div>
        <div class="alan">
            <label for="mekanAdi">Mekan Adı</label>
            <input type="text" id="mekanAdi" maxlength="40" placeholder="Örn. Yıldız Cafe" autocomplete="organization">
        </div>
        <div class="alan">
            <label for="telefon">Telefon Numarası</label>
            <input type="tel" id="telefon" inputmode="tel" maxlength="14" placeholder="0555 000 00 00" autocomplete="tel">
        </div>
    </div>

    <button type="button" class="btn btn-ana" id="olusturBtn">Afişi Oluştur</button>

    <div class="kart onizleme" id="onizleme" style="margin-top:16px;">
        <h2><i>3</i> Afişin Hazır</h2>
        <canvas id="tuval"></canvas>
        <div class="butonlar">
            <button type="button" class="btn btn-ana" id="paylasBtn">WhatsApp&#39;ta Paylaş</button>
            <button type="button" class="btn btn-ikincil" id="indirBtn">Görseli İndir</button>
        </div>
        <p class="bilgi">Paylaşım desteklenmiyorsa görseli indirip WhatsApp&#39;tan gönderebilirsin.</p>
    </div>

<?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
(function () {
    'use strict';

    var liste = document.getElementById('sablonListe');
    if (!liste) return;

    var mac        = document.getElementById('mac');
    var mekanAdi   = document.getElementById('mekanAdi');
    var telefon    = document.getElementById('telefon');
    var olusturBtn = document.getElementById('olusturBtn');
    var onizleme   = document.getElementById('onizleme');
    var tuval      = document.getElementById('tuval');
    var indirBtn   = document.getElementById('indirBtn');
    var paylasBtn  = document.getElementById('paylasBtn');
    var toastEl    = document.getElementById('toast');
    var toastZaman = null;
    var sonBlob    = null;

    function toast(mesaj) {
        toastEl.textContent = mesaj;
        toastEl.classList.add('acik');
        clearTimeout(toastZaman);
        toastZaman = setTimeout(function () { toastEl.classList.remove('acik'); }, 2600);
    }

    /* --- Sablon secimi --- */
    liste.addEventListener('click', function (e) {
        var btn = e.target.closest('.sablon');
        if (!btn) return;
        liste.querySelectorAll('.sablon').forEach(function (el) { el.classList.remove('secili'); });
        btn.classList.add('secili');
    });

    function seciliUrl() {
        var el = liste.querySelector('.sablon.secili');
        return el ? el.dataset.url : null;
    }

    /* --- Telefon bicimi: 0555 000 00 00 (nasil girilirse girilsin) --- */
    function telefonBicimle(deger) {
        var r = String(deger || '').replace(/\D/g, '');
        if (r.slice(0, 2) === '90') r = r.slice(2);          // +90 / 90 onekini at
        r = r.replace(/^0+/, '');                            // bastaki sifirlari at
        r = r.slice(0, 10);                                  // 10 haneli abone numarasi
        if (!r) return '';
        var m = '0' + r.slice(0, 3);
        if (r.length > 3) m += ' ' + r.slice(3, 6);
        if (r.length > 6) m += ' ' + r.slice(6, 8);
        if (r.length > 8) m += ' ' + r.slice(8, 10);
        return m;
    }

    telefon.addEventListener('input', function () {
        telefon.value = telefonBicimle(telefon.value);
    });

    /* --- Yuvarlak koseli kutu --- */
    function kutu(ctx, x, y, g, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + g, y, x + g, y + h, r);
        ctx.arcTo(x + g, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + g, y, r);
        ctx.closePath();
    }

    /* --- Kutuya sigana kadar font kucult --- */
    function sigdir(ctx, metin, maxGenislik, baslangic, kalinlik) {
        var boyut = baslangic;
        do {
            ctx.font = kalinlik + ' ' + boyut + 'px Poppins, Arial, sans-serif';
            if (ctx.measureText(metin).width <= maxGenislik) break;
            boyut -= 2;
        } while (boyut > 10);
        return boyut;
    }

    /* --- Gorselde mekan adi: sinirdan uzunsa son bosluktan kirp --- */
    var AD_SINIR = 20;

    function adKirp(metin, sinir) {
        if (metin.length <= sinir) return metin;
        var kesit  = metin.slice(0, sinir);
        var bosluk = kesit.lastIndexOf(' ');
        return (bosluk > 0 ? kesit.slice(0, bosluk) : kesit).trim();
    }

    /* --- Telefon ikonu --- */
    function telefonIkonu(ctx, x, y, boy, renk) {
        ctx.save();
        ctx.translate(x, y);
        ctx.scale(boy / 24, boy / 24);
        ctx.fillStyle = renk;
        ctx.fill(new Path2D('M6.6 10.8c1.4 2.8 3.8 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.2.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.3 2.2z'));
        ctx.restore();
    }

    /* --- Logo yukle (bulunamazsa null doner, afis yine olusur) --- */
    function logoYukle(url) {
        return new Promise(function (coz) {
            if (!url) { coz(null); return; }
            var im = new Image();
            im.onload  = function () { coz(im); };
            im.onerror = function () { coz(null); };
            im.src = url;
        });
    }

    /* --- Takim logosu: beyaz daire zemin uzerine orantili logo --- */
    function logoCiz(ctx, im, cx, cy, boy) {
        if (!im) return;
        ctx.beginPath();
        ctx.arc(cx, cy, boy / 2, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)';
        ctx.fill();

        var ic = boy * 0.74;
        var o  = Math.min(ic / im.naturalWidth, ic / im.naturalHeight);
        var g  = im.naturalWidth * o;
        var h  = im.naturalHeight * o;
        ctx.drawImage(im, cx - g / 2, cy - h / 2, g, h);
    }

    /* --- Gorselin ust kismi: mac bilgisi, altinda takim logolari --- */
    function ustBilgi(ctx, G, ev, dep, zaman, logolar) {
        if (!ev && !dep) return;

        var evAd  = ev.toLocaleUpperCase('tr-TR');
        var depAd = dep.toLocaleUpperCase('tr-TR');

        var logoBoy = Math.round(G * 0.26);              // logolar buyuk
        var aralik  = logoBoy * 0.72;                    // iki logo arasi (VS bosluğu)
        var sutunG  = G * 0.38;                          // isim sutunu genisligi

        // Iki isim ayni boyutta olsun: ikisinin de sigdigi en kucuk boyut
        var bi = Math.min(
            sigdir(ctx, evAd,  sutunG, Math.round(G * 0.052), '800'),
            sigdir(ctx, depAd, sutunG, Math.round(G * 0.052), '800')
        );
        var bz = zaman ? sigdir(ctx, zaman, G * 0.8, Math.round(G * 0.038), '600') : 0;

        var ustBosluk = G * 0.05;
        var zamanY    = ustBosluk + bz / 2;
        var logoY     = ustBosluk + (bz ? bz * 1.5 : 0) + logoBoy / 2;
        var isimY     = logoY + logoBoy / 2 + bi * 0.95;
        var yukseklik = isimY + bi * 1.5;

        var ov = ctx.createLinearGradient(0, 0, 0, yukseklik);
        ov.addColorStop(0,    'rgba(8,10,13,0.92)');
        ov.addColorStop(0.72, 'rgba(8,10,13,0.70)');
        ov.addColorStop(1,    'rgba(8,10,13,0)');
        ctx.fillStyle = ov;
        ctx.fillRect(0, 0, G, yukseklik);

        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';

        if (bz) {
            ctx.font      = '600 ' + bz + 'px Poppins, Arial, sans-serif';
            ctx.fillStyle = '#D7DCE4';
            ctx.fillText(zaman, G / 2, zamanY);
        }

        var solX = G / 2 - aralik / 2 - logoBoy / 2;
        var sagX = G / 2 + aralik / 2 + logoBoy / 2;

        logoCiz(ctx, logolar[0], solX, logoY, logoBoy);
        logoCiz(ctx, logolar[1], sagX, logoY, logoBoy);

        ctx.font      = '800 ' + Math.round(logoBoy * 0.26) + 'px Poppins, Arial, sans-serif';
        ctx.fillStyle = '#E30613';
        ctx.fillText('VS', G / 2, logoY);

        ctx.font      = '800 ' + bi + 'px Poppins, Arial, sans-serif';
        ctx.fillStyle = '#FFFFFF';
        ctx.fillText(evAd,  solX, isimY);
        ctx.fillText(depAd, sagX, isimY);
    }

    /* --- Afisi ciz --- */
    function ciz(gorsel) {
        var ad  = mekanAdi.value.trim();
        var tel = telefonBicimle(telefon.value);
        var adBuyuk = adKirp(ad, AD_SINIR).toLocaleUpperCase('tr-TR');

        var secili   = mac ? mac.options[mac.selectedIndex] : null;
        var macEv    = (secili && secili.value) ? (secili.dataset.ev    || '') : '';
        var macDep   = (secili && secili.value) ? (secili.dataset.dep   || '') : '';
        var macZaman = (secili && secili.value) ? (secili.dataset.zaman || '') : '';

        var evLogoUrl  = (secili && secili.value) ? (secili.dataset.evLogo  || '') : '';
        var depLogoUrl = (secili && secili.value) ? (secili.dataset.depLogo || '') : '';

        var G       = gorsel.naturalWidth;
        var gorselY = gorsel.naturalHeight;
        var bantY   = Math.round(G * 0.26);
        var ctx     = tuval.getContext('2d');

        tuval.width  = G;
        tuval.height = gorselY + bantY;

        ctx.drawImage(gorsel, 0, 0, G, gorselY);

        var zemin = ctx.createLinearGradient(0, gorselY, 0, gorselY + bantY);
        zemin.addColorStop(0, '#15181D');
        zemin.addColorStop(1, '#0A0C0F');
        ctx.fillStyle = zemin;
        ctx.fillRect(0, gorselY, G, bantY);

        ctx.fillStyle = '#E30613';
        ctx.fillRect(0, gorselY, G, Math.max(3, Math.round(G * 0.008)));

        var kenar = G * 0.07;
        var icG   = G - kenar * 2;
        var imlec = gorselY + bantY * 0.32;

        if (ad) {
            var b1 = sigdir(ctx, adBuyuk, icG, Math.round(G * 0.085), '800');
            ctx.fillStyle   = '#FFFFFF';
            ctx.textAlign   = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(adBuyuk, G / 2, imlec);
            imlec += b1 * 1.15;
        }

        if (tel) {
            var b2      = sigdir(ctx, tel, icG * 0.72, Math.round(G * 0.062), '700');
            var metinG  = ctx.measureText(tel).width;
            var ikonBoy = b2 * 1.05;
            var bosluk  = b2 * 0.45;
            var rozetG  = metinG + ikonBoy + bosluk * 3;
            var rozetY  = b2 * 1.9;
            var rozetX  = (G - rozetG) / 2;
            var rozetUst = imlec - rozetY / 2;

            ctx.fillStyle = '#E30613';
            kutu(ctx, rozetX, rozetUst, rozetG, rozetY, rozetY / 2);
            ctx.fill();

            telefonIkonu(ctx, rozetX + bosluk * 1.3, rozetUst + (rozetY - ikonBoy) / 2, ikonBoy, '#FFFFFF');

            ctx.fillStyle    = '#FFFFFF';
            ctx.textAlign    = 'left';
            ctx.textBaseline = 'middle';
            ctx.font         = '700 ' + b2 + 'px Poppins, Arial, sans-serif';
            ctx.fillText(tel, rozetX + bosluk * 1.3 + ikonBoy + bosluk, rozetUst + rozetY / 2);
        }

        return Promise.all([logoYukle(evLogoUrl), logoYukle(depLogoUrl)]).then(function (logolar) {
            ustBilgi(ctx, G, macEv, macDep, macZaman, logolar);
            return new Promise(function (cozumle) {
                tuval.toBlob(function (blob) { sonBlob = blob; cozumle(blob); }, 'image/png');
            });
        });
    }

    function dosyaAdi() {
        var ad = mekanAdi.value.trim().toLocaleLowerCase('tr-TR')
                 .replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g')
                 .replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c')
                 .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return (ad || 'afis') + '.png';
    }

    olusturBtn.addEventListener('click', function () {
        var url = seciliUrl();
        if (!url) { toast('Önce bir şablon seç.'); return; }
        if (!mekanAdi.value.trim()) { toast('Mekan adını yaz.'); mekanAdi.focus(); return; }
        if (telefonBicimle(telefon.value).length < 14) {
            toast('Telefon numarasını 10 haneli olarak yaz.');
            telefon.focus();
            return;
        }
        telefon.value = telefonBicimle(telefon.value);

        olusturBtn.disabled = true;
        olusturBtn.textContent = 'Oluşturuluyor...';

        var gorsel = new Image();
        gorsel.onload = function () {
            var hazir = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
            hazir.then(function () { return ciz(gorsel); }).then(function () {
                onizleme.classList.add('acik');
                onizleme.scrollIntoView({ behavior: 'smooth', block: 'start' });
                olusturBtn.disabled = false;
                olusturBtn.textContent = 'Afişi Yeniden Oluştur';
            });
        };
        gorsel.onerror = function () {
            toast('Şablon görseli yüklenemedi.');
            olusturBtn.disabled = false;
            olusturBtn.textContent = 'Afişi Oluştur';
        };
        gorsel.src = url;
    });

    indirBtn.addEventListener('click', function () {
        if (!sonBlob) return;
        var adres = URL.createObjectURL(sonBlob);
        var a = document.createElement('a');
        a.href = adres;
        a.download = dosyaAdi();
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(adres); }, 4000);
    });

    paylasBtn.addEventListener('click', function () {
        if (!sonBlob) return;
        var dosya = new File([sonBlob], dosyaAdi(), { type: 'image/png' });

        if (navigator.canShare && navigator.canShare({ files: [dosya] })) {
            navigator.share({ files: [dosya], title: mekanAdi.value.trim() }).catch(function () {});
        } else {
            toast('Bu tarayıcı paylaşımı desteklemiyor, görsel indiriliyor.');
            indirBtn.click();
        }
    });
})();
</script>
</body>
</html>
