/**
 * Digiturk Portal - PWA İstemci Mantığı
 *  - Service Worker kaydı
 *  - Android/Windows: beforeinstallprompt -> "Uygulamayı Yükle" butonu
 *  - iOS: otomatik banner yok -> "Ana Ekrana Ekle" yönergesi
 *  - Güncelleme: yeni sürüm hazırsa "Yenile" bildirimi
 *  - Push: VAPID anahtarı sunucuda tanımlıysa abonelik
 */
(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) return;

  var deferredPrompt = null;
  var swReg = null;

  // ─── Yardımcı: uygulama kurulu mu (standalone) ───
  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
           window.navigator.standalone === true;
  }

  // ─── Service Worker kaydı ───
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/admin/service-worker.js', { scope: '/admin/' })
      .then(function (reg) {
        swReg = reg;

        // Güncelleme kontrolü
        reg.addEventListener('updatefound', function () {
          var yeni = reg.installing;
          if (!yeni) return;
          yeni.addEventListener('statechange', function () {
            if (yeni.state === 'installed' && navigator.serviceWorker.controller) {
              guncellemeBildirimiGoster(yeni);
            }
          });
        });
      })
      .catch(function (err) { console.warn('SW kayıt hatası:', err); });

    // Yeni SW aktif olunca sayfayı tazele
    var yenileniyor = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (yenileniyor) return;
      yenileniyor = true;
      window.location.reload();
    });

    if (!isStandalone()) iosBannerKontrol();

    // Push bildirim kurulumu (SW hazır olunca)
    navigator.serviceWorker.ready.then(pushKurulum).catch(function () {});
  });

  // ─── Android / Windows: kurulabilir ───
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    installButonuGoster();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    installButonuGizle();
    if (window.Swal) {
      Swal.fire({ icon: 'success', title: 'Uygulama kuruldu', timer: 2000, showConfirmButton: false });
    }
  });

  // ─── Install butonu (yüzen) ───
  function installButonuGoster() {
    if (document.getElementById('pwaInstallBtn') || isStandalone()) return;
    var btn = document.createElement('button');
    btn.id = 'pwaInstallBtn';
    btn.type = 'button';
    btn.className = 'btn btn-primary shadow';
    btn.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:1050;border-radius:50px;padding:10px 18px;';
    btn.innerHTML = '<i class="bi bi-download me-1"></i> Uygulamayı Yükle';
    btn.addEventListener('click', function () {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        installButonuGizle();
      });
    });
    document.body.appendChild(btn);
  }
  function installButonuGizle() {
    var b = document.getElementById('pwaInstallBtn');
    if (b) b.remove();
  }

  // ─── iOS banner (Safari otomatik prompt vermez) ───
  function iosBannerKontrol() {
    var ua = window.navigator.userAgent;
    var iOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    var safari = /^((?!chrome|android|crios|fxios).)*safari/i.test(ua);
    if (!iOS || !safari) return;
    if (localStorage.getItem('pwaIosBannerKapatildi') === '1') return;

    var bar = document.createElement('div');
    bar.id = 'pwaIosBanner';
    bar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:12px;padding:12px 14px;box-shadow:0 6px 24px rgba(0,0,0,.15);font-size:.9rem;color:#212529;';
    bar.innerHTML =
      '<div style="display:flex;align-items:center;gap:10px;">' +
        '<i class="bi bi-phone" style="font-size:1.4rem;color:#0d6efd;"></i>' +
        '<div style="flex:1;">Ana ekrana eklemek için <strong>Paylaş <i class="bi bi-box-arrow-up"></i></strong> ' +
        've <strong>"Ana Ekrana Ekle"</strong> seçin.</div>' +
        '<button type="button" id="pwaIosKapat" class="btn btn-sm btn-light" aria-label="Kapat">&times;</button>' +
      '</div>';
    document.body.appendChild(bar);
    document.getElementById('pwaIosKapat').addEventListener('click', function () {
      localStorage.setItem('pwaIosBannerKapatildi', '1');
      bar.remove();
    });
  }

  // ─── Push bildirim ───
  function urlB64ToUint8(base64) {
    var pad = '='.repeat((4 - base64.length % 4) % 4);
    var b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b64);
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  /** Sunucudaki VAPID public anahtarını getirir; tanımlı değilse null döner. */
  function anahtarGetir() {
    return fetch('/admin/api/pwa-anahtar.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { return (d && d.success && d.publicKey) ? d.publicKey : null; })
      .catch(function () { return null; });
  }

  function pushKurulum(reg) {
    if (!('PushManager' in window) || !('Notification' in window)) return;
    if (Notification.permission === 'denied') return;

    // Anahtar yoksa (VAPID henüz tanımlanmamış) hiçbir UI gösterme.
    anahtarGetir().then(function (publicKey) {
      if (!publicKey) return;
      if (Notification.permission === 'granted') {
        aboneOl(reg, publicKey);            // izin var -> aboneliği garantile
      } else {
        bildirimButonuGoster(reg, publicKey); // kullanıcıya sor
      }
    });
  }

  function bildirimButonuGoster(reg, publicKey) {
    if (document.getElementById('pwaPushBtn')) return;
    var btn = document.createElement('button');
    btn.id = 'pwaPushBtn';
    btn.type = 'button';
    btn.className = 'btn btn-outline-primary btn-sm shadow-sm';
    btn.style.cssText = 'position:fixed;right:16px;bottom:70px;z-index:1049;border-radius:50px;padding:8px 14px;background:#fff;';
    btn.innerHTML = '<i class="bi bi-bell me-1"></i> Bildirimleri Aç';
    btn.addEventListener('click', function () {
      Notification.requestPermission().then(function (izin) {
        if (izin === 'granted') {
          btn.remove();
          aboneOl(reg, publicKey);
        }
      });
    });
    document.body.appendChild(btn);
  }

  function aboneOl(reg, publicKey) {
    reg.pushManager.getSubscription().then(function (mevcut) {
      if (mevcut) return sunucuyaKaydet(mevcut);
      return reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlB64ToUint8(publicKey),
      }).then(sunucuyaKaydet);
    }).catch(function (e) { console.warn('Push abonelik hatası:', e); });
  }

  function sunucuyaKaydet(sub) {
    var j = sub.toJSON();
    return fetch('/admin/api/pwa-abone.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'kaydet',
        endpoint: sub.endpoint,
        p256dh: j.keys && j.keys.p256dh,
        auth: j.keys && j.keys.auth,
      }),
    }).catch(function () {});
  }

  // ─── Güncelleme bildirimi ───
  function guncellemeBildirimiGoster(yeniSW) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:1060;background:#0d6efd;color:#fff;border-radius:8px;padding:10px 16px;box-shadow:0 4px 16px rgba(0,0,0,.2);font-size:.9rem;';
    t.innerHTML = 'Yeni sürüm mevcut. <button type="button" id="pwaYenile" class="btn btn-sm btn-light ms-2">Yenile</button>';
    document.body.appendChild(t);
    document.getElementById('pwaYenile').addEventListener('click', function () {
      yeniSW.postMessage('SKIP_WAITING');
      t.remove();
    });
  }
})();
