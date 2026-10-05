/**
 * Oturum Takip - Süre dolmadan önce uyarı ve uzatma
 *
 * Süre bitimine UYARI_ESIGI saniye kala ekranın ortasında bir uyarı açılır.
 * Kullanıcı oturumu uzatabilir veya çıkış yapabilir; işlem yapılmazsa
 * geri sayım bitince otomatik olarak login sayfasına yönlendirilir.
 *
 * Başlangıç değerleri footer.php içinde window.OTURUM_TAKIP ile verilir:
 *   { kalan: <saniye>, omur: <saniye> }
 *
 * Hiçbir kütüphaneye bağımlı değildir (Bootstrap sayfa bazında yükleniyor,
 * footer'dan sonra geldiği için burada mevcut olduğu garanti edilemez).
 */
(function () {
    'use strict';

    var ayar = window.OTURUM_TAKIP || {};

    var UYARI_ESIGI = 30;   // saniye - uyarının açılacağı kalan süre
    var API = '/admin/api/oturum.php';

    var kalan     = parseInt(ayar.kalan, 10) || 0;
    var sayacId   = null;
    var acik      = false;
    var overlay   = null;
    var sayacEl   = null;

    // ── Uyarı penceresi ────────────────────────────────────────────────
    function stilEkle() {
        if (document.getElementById('oturumTakipStil')) return;
        var s = document.createElement('style');
        s.id = 'oturumTakipStil';
        // Renkler Bootstrap tema değişkenlerinden alınır; panel açık/koyu temada
        // olsun kutu her zaman sayfayla uyumlu görünür. Değişken yoksa yedek değer devreye girer.
        s.textContent =
            '#oturumTakipOverlay{position:fixed;inset:0;z-index:2147483000;display:flex;' +
            'align-items:center;justify-content:center;background:rgba(0,0,0,.5);' +
            'font-family:inherit}' +
            '#oturumTakipKutu{background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);' +
            'border:1px solid var(--bs-border-color,rgba(0,0,0,.175));border-radius:.5rem;' +
            'max-width:400px;width:calc(100% - 32px);box-shadow:0 .5rem 1.5rem rgba(0,0,0,.35);' +
            'overflow:hidden}' +
            '#oturumTakipKutu .otk-bas{padding:.9rem 1.15rem;font-weight:600;font-size:1rem;' +
            'display:flex;align-items:center;gap:.55rem;' +
            'border-bottom:1px solid var(--bs-border-color,rgba(0,0,0,.175))}' +
            '#oturumTakipKutu .otk-bas .otk-ikon{color:var(--bs-warning,#ffc107);font-size:1.15rem;' +
            'line-height:1}' +
            '#oturumTakipKutu .otk-govde{padding:1.25rem 1.15rem;font-size:.9rem;line-height:1.5;' +
            'text-align:center;color:var(--bs-secondary-color,#6c757d)}' +
            '#oturumTakipKutu .otk-sayac{font-size:2.5rem;font-weight:700;' +
            'color:var(--bs-danger,#dc3545);margin-bottom:.5rem;letter-spacing:-.02em;' +
            'font-variant-numeric:tabular-nums}' +
            '#oturumTakipKutu .otk-sayac small{font-size:1rem;font-weight:500;margin-left:.15rem}' +
            '#oturumTakipKutu .otk-alt{padding:.85rem 1.15rem;display:flex;gap:.5rem;' +
            'justify-content:flex-end;flex-wrap:wrap;' +
            'background:var(--bs-tertiary-bg,#f8f9fa);' +
            'border-top:1px solid var(--bs-border-color,rgba(0,0,0,.175))}' +
            '#oturumTakipKutu button{border-radius:.375rem;padding:.45rem .9rem;font-size:.875rem;' +
            'cursor:pointer;font-weight:500;line-height:1.5}' +
            '#otkUzat{background:var(--bs-primary,#0d6efd);color:#fff;border:1px solid transparent}' +
            '#otkUzat:disabled{opacity:.65;cursor:default}' +
            '#otkCikis{background:transparent;color:var(--bs-body-color,#212529);' +
            'border:1px solid var(--bs-border-color,#dee2e6)}';
        document.head.appendChild(s);
    }

    function pencereAc() {
        if (acik) return;
        acik = true;
        stilEkle();

        overlay = document.createElement('div');
        overlay.id = 'oturumTakipOverlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.innerHTML =
            '<div id="oturumTakipKutu">' +
              '<div class="otk-bas">' +
                '<i class="bi bi-clock-history otk-ikon"></i>' +
                '<span>Oturumunuz sona ermek üzere</span>' +
              '</div>' +
              '<div class="otk-govde">' +
                '<div class="otk-sayac"><span id="otkSayac">' + kalan + '</span><small>sn</small></div>' +
                'Süre dolduğunda güvenlik nedeniyle çıkış yapılacak ve ' +
                'kaydedilmemiş bilgiler kaybolacaktır.' +
              '</div>' +
              '<div class="otk-alt">' +
                '<button type="button" id="otkCikis">Çıkış Yap</button>' +
                '<button type="button" id="otkUzat">Oturumu Uzat</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        sayacEl = document.getElementById('otkSayac');
        document.getElementById('otkUzat').addEventListener('click', uzat);
        document.getElementById('otkCikis').addEventListener('click', cikis);
        document.getElementById('otkUzat').focus();
    }

    function pencereKapat() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        sayacEl = null;
        acik = false;
    }

    // ── İşlemler ───────────────────────────────────────────────────────
    function cikis() {
        window.location.href = '/admin/logout.php';
    }

    function zamanAsimi() {
        durdur();
        // Giriş sonrası kaldığı sayfaya dönebilmesi için mevcut adres taşınır
        var donus = window.location.pathname + window.location.search;
        window.location.href = '/admin/login.php?zaman_asimi=1&donus=' + encodeURIComponent(donus);
    }

    function uzat() {
        var btn = document.getElementById('otkUzat');
        if (btn) { btn.disabled = true; btn.textContent = 'Uzatılıyor...'; }

        istek('uzat')
            .then(function (d) {
                if (!d || !d.oturum) { zamanAsimi(); return; }
                kalan = d.kalan;
                pencereKapat();
                if (typeof showToast === 'function') {
                    showToast('Oturumunuz uzatıldı.', 'success');
                }
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.textContent = 'Oturumu Uzat'; }
            });
    }

    function istek(action) {
        return fetch(API + '?action=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); });
    }

    // Sekme arka plandayken tarayıcı zamanlayıcıyı yavaşlatır; dönüşte
    // kalan süre sunucudan tazelenir.
    function senkronize() {
        istek('durum')
            .then(function (d) {
                if (!d) return;
                if (!d.oturum) { zamanAsimi(); return; }
                kalan = d.kalan;
                if (acik && kalan > UYARI_ESIGI) pencereKapat();
            })
            .catch(function () { /* ağ hatası: yerel sayaçla devam */ });
    }

    // ── Sayaç ──────────────────────────────────────────────────────────
    function tik() {
        kalan--;

        if (kalan <= 0) { zamanAsimi(); return; }

        if (kalan <= UYARI_ESIGI) {
            pencereAc();
            if (sayacEl) sayacEl.textContent = kalan;
        }
    }

    function durdur() {
        if (sayacId) clearInterval(sayacId);
        sayacId = null;
    }

    if (kalan > 0) {
        sayacId = setInterval(tik, 1000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) senkronize();
        });
    }

    // ── Oturumu biten isteklerin yakalanması ───────────────────────────
    //
    // Geri sayım her durumu yakalayamaz: bilgisayar uykuya girerse veya sekme
    // uzun süre donarsa süre farkında olmadan dolar. O noktada yapılan ilk AJAX
    // isteği sunucudan 401 döner. Yakalanmazsa sayfada ham bir hata belirir ve
    // kullanıcı sayfanın bozuk olduğunu sanır.

    var bitisGosterildi = false;

    function oturumBittiPencere() {
        if (bitisGosterildi) return;
        bitisGosterildi = true;

        durdur();
        pencereKapat();
        stilEkle();

        var el = document.createElement('div');
        el.id = 'oturumTakipOverlay';
        el.setAttribute('role', 'alertdialog');
        el.innerHTML =
            '<div id="oturumTakipKutu">' +
              '<div class="otk-bas">' +
                '<i class="bi bi-clock-history otk-ikon"></i>' +
                '<span>Oturumunuz sona erdi</span>' +
              '</div>' +
              '<div class="otk-govde">' +
                'Uzun süre işlem yapılmadığı için oturumunuz kapatıldı.<br>' +
                'Kaldığınız yerden devam etmek için tekrar giriş yapın.' +
              '</div>' +
              '<div class="otk-alt">' +
                '<button type="button" id="otkGiris">Giriş Sayfasına Git</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(el);

        var btn = document.getElementById('otkGiris');
        btn.style.background = 'var(--bs-primary,#0d6efd)';
        btn.style.color = '#fff';
        btn.style.border = '1px solid transparent';
        btn.addEventListener('click', zamanAsimi);
        btn.focus();
    }

    function oturumBittiYaniti(veri) {
        return !!(veri && veri.oturum_bitti);
    }

    // jQuery ($.ajax) - sayfaların büyük çoğunluğu bunu kullanıyor
    if (window.jQuery) {
        window.jQuery(document).ajaxError(function (olay, xhr) {
            if (xhr && xhr.status === 401 && oturumBittiYaniti(xhr.responseJSON)) {
                oturumBittiPencere();
            }
        });
    }

    // fetch() - gövdeyi tüketmemek için klon üzerinden bakılır
    if (window.fetch) {
        var asilFetch = window.fetch;
        window.fetch = function () {
            return asilFetch.apply(this, arguments).then(function (yanit) {
                if (yanit && yanit.status === 401) {
                    yanit.clone().json()
                        .then(function (veri) {
                            if (oturumBittiYaniti(veri)) oturumBittiPencere();
                        })
                        .catch(function () { /* JSON değil: ilgilendiğimiz durum değil */ });
                }
                return yanit;
            });
        };
    }
})();
