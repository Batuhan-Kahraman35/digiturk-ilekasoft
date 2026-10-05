/**
 * Digiturk Portal - Service Worker
 * Kapsam: /admin/
 *
 * Strateji:
 *   - Sayfa gezinme (navigation)  -> NetworkFirst, offline'da offline.html
 *   - /admin/api/ ve POST         -> NetworkOnly (dinamik veri cache'lenmez)
 *   - Statik dosya (css/js/img)   -> StaleWhileRevalidate
 *
 * Sürüm değişince CACHE_VERSION artırılır -> eski cache otomatik silinir.
 */

const CACHE_VERSION = 'v1.0.0';
const CACHE_PREFIX  = 'digiturk-pwa-';
const CACHE_NAME    = CACHE_PREFIX + CACHE_VERSION;
const OFFLINE_URL   = '/admin/offline.html';

// Kurulumda önbelleğe alınacak temel dosyalar.
// NOT: Buradaki her yol gerçekten var olmalı; tek bir 404 addAll()'u komple iptal eder.
const PRECACHE = [
  OFFLINE_URL,
  '/admin/assets/css/adminlte.min.css',
  '/admin/assets/css/custom.css',
  '/admin/assets/js/pwa.js',
];

// ─── Install ───
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE).catch(() => {}))
  );
  // Yeni SW hemen "waiting"e geçmesin, pwa.js kontrol edecek
});

// ─── Activate: eski cache temizliği ───
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k.startsWith(CACHE_PREFIX) && k !== CACHE_NAME)
            .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ─── Fetch ───
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Sadece GET cache'lenir; POST/PUT vb. doğrudan ağa gider
  if (req.method !== 'GET') return;

  // Farklı origin (CDN: jsdelivr vb.) -> karışma, tarayıcı kendi cache'ini kullansın
  if (url.origin !== self.location.origin) return;

  // API istekleri: her zaman ağdan (dinamik veri)
  if (url.pathname.startsWith('/admin/api/')) {
    event.respondWith(fetch(req).catch(() =>
      new Response(JSON.stringify({ success: false, offline: true, message: 'Çevrimdışısınız.' }),
        { headers: { 'Content-Type': 'application/json' }, status: 503 })
    ));
    return;
  }

  // Sayfa gezinmeleri: NetworkFirst -> offline'da offline.html
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // Statik dosyalar: StaleWhileRevalidate
  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => {
        if (res && res.status === 200 && res.type !== 'opaque') {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});

// ─── Push bildirim geldiğinde ───
self.addEventListener('push', (event) => {
  let veri = { baslik: 'Bildirim', govde: '', url: '/admin/' };
  try {
    if (event.data) veri = Object.assign(veri, event.data.json());
  } catch (e) {
    if (event.data) veri.govde = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(veri.baslik, {
      body: veri.govde,
      icon: '/admin/icon.php?size=192',
      badge: '/admin/icon.php?size=192',
      data: { url: veri.url || '/admin/' },
      tag: veri.tag || undefined,
      renotify: !!veri.tag,
    })
  );
});

// ─── Bildirime tıklanınca ───
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const hedef = (event.notification.data && event.notification.data.url) || '/admin/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((liste) => {
      for (const c of liste) {
        if (c.url.indexOf(hedef) !== -1 && 'focus' in c) return c.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(hedef);
    })
  );
});

// ─── pwa.js'ten gelen mesajlar (güncelleme kontrolü) ───
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
